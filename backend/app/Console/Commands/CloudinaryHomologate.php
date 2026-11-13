<?php

namespace App\Console\Commands;

use App\Contracts\HomologationCloudinaryClient;
use Illuminate\Console\Command;

class CloudinaryHomologate extends Command
{
    protected $signature = 'cloudinary:homologate {phase=prepare : prepare, upload, verify or delete}
        {--run= : Exact run ID from preparation}
        {--confirm= : Exact public_id required for upload and delete}
        {--folder-mode= : Required for preparation: dynamic or fixed}';
    protected $description = 'Manually homologate exactly one synthetic Cloudinary image, without database access';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function handle(): int
    {
        // Defense in depth for in-process Artisan calls, in addition to the
        // console bootstrap guard. No database service is resolved here.
        config(['database.connections' => [], 'database.default' => 'disabled',
            'session.driver' => 'array', 'cache.default' => 'array',
            'auth.passwords.users.store' => 'array']);
        if (getenv('CLOUDINARY_HOMOLOGATION_ENABLED') !== '1') {
            $this->line('Diagnostic: homologation_disabled');
            $this->error('Homologation disabled. Explicit process opt-in is required.');
            return self::FAILURE;
        }
        $phase = $this->argument('phase');
        if (! in_array($phase, ['prepare', 'upload', 'verify', 'delete'], true)) {
            $this->line('Diagnostic: phase_invalid');
            $this->error('Invalid phase.');
            return self::FAILURE;
        }
        $lock = null;
        $diagnostic = 'manifest_directory_failed';
        try {
            $root = storage_path('app/private/cloudinary-homologation');
            if (is_link($root) || (! is_dir($root) && ! mkdir($root, 0700, true))) {
                throw new \RuntimeException;
            }
            if ($phase === 'prepare') {
                $diagnostic = 'preparation_configuration_invalid';
                $mode = $this->option('folder-mode');
                $cloud = getenv('CLOUDINARY_CLOUD_NAME');
                if (! in_array($mode, ['dynamic', 'fixed'], true) || ! is_string($cloud) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $cloud)) {
                    throw new \RuntimeException;
                }
                $run = bin2hex(random_bytes(16));
                $manifest = ['version' => 1, 'run' => $run, 'resource' => bin2hex(random_bytes(16)),
                    'cloud_name' => $cloud, 'folder_mode' => $mode, 'state' => 'reserved',
                    'created_at' => gmdate(DATE_ATOM)];
                $manifest['public_id'] = 'adoptplace/pets/homologation-'.$run.'/'.$manifest['resource'];
                $manifest['asset_folder'] = 'adoptplace-homologation/'.$run;
                $diagnostic = 'manifest_save_failed';
                $this->save($root, $manifest);
                $this->line('Run: '.$run);
                $this->line('public_id: '.$manifest['public_id']);
                $this->info('Prepared locally. No remote operation was performed.');
                return self::SUCCESS;
            }
            $run = $this->option('run');
            $diagnostic = 'run_invalid';
            if (! is_string($run) || ! preg_match('/^[a-f0-9]{32}$/D', $run)) {
                throw new \RuntimeException;
            }
            $diagnostic = 'manifest_lock_failed';
            $lock = fopen($root.'/'.$run.'.lock', 'c+');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException;
            }
            chmod($root.'/'.$run.'.lock', 0600);
            $path = $root.'/'.$run.'.json';
            $diagnostic = 'manifest_read_failed';
            if (is_link($path) || ! is_file($path)) {
                throw new \RuntimeException;
            }
            $diagnostic = 'manifest_json_invalid';
            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $diagnostic = 'manifest_validation_failed';
            $this->validateManifest($manifest, $run);
            $diagnostic = 'confirmation_mismatch';
            if (in_array($phase, ['upload', 'delete'], true) && $this->option('confirm') !== $manifest['public_id']) {
                throw new \RuntimeException;
            }
            $diagnostic = 'client_resolution_failed';
            $client = app(HomologationCloudinaryClient::class);
            if ($phase === 'upload') {
                $diagnostic = 'upload_state_invalid';
                if ($manifest['state'] !== 'reserved') {
                    throw new \RuntimeException;
                }
                $diagnostic = 'configuration_preflight_failed';
                $client->preflight();
                $diagnostic = 'synthetic_image_write_failed';
                $image = $root.'/'.$run.'.png';
                if (file_put_contents($image, base64_decode(self::PNG, true)) === false) {
                    throw new \RuntimeException;
                }
                chmod($image, 0600);
                $manifest['state'] = 'upload_attempted';
                $diagnostic = 'upload_intent_save_failed';
                $this->save($root, $manifest); // Durable local intent before I/O.
                $options = ['public_id' => $manifest['public_id'], 'resource_type' => 'image',
                    'type' => 'upload', 'overwrite' => false,
                    'context' => 'adoptplace_homologation_run='.$run];
                if ($manifest['folder_mode'] === 'dynamic') {
                    $options['asset_folder'] = $manifest['asset_folder'];
                }
                try {
                    $diagnostic = 'upload_remote_outcome_uncertain';
                    $result = $client->upload($image, $options);
                    $diagnostic = 'upload_response_invalid';
                    $this->validateAsset($result, $manifest);
                    $diagnostic = 'upload_existing_asset_rejected';
                    if (! empty($result['existing'])) { throw new \RuntimeException; }
                    $manifest['asset_id'] = $result['asset_id'];
                    $manifest['state'] = 'uploaded';
                    $diagnostic = 'upload_result_save_failed';
                    $this->save($root, $manifest);
                } finally {
                    if (is_file($image)) { unlink($image); }
                }
            } elseif ($phase === 'verify') {
                $diagnostic = 'verification_state_invalid';
                if ($manifest['state'] === 'reserved') { throw new \RuntimeException; }
                $diagnostic = 'configuration_preflight_failed';
                $client->preflight();
                $diagnostic = 'verification_remote_failed';
                $result = $client->inspect($manifest['public_id']);
                if ($result === null) {
                    $manifest['last_observation'] = 'not_found';
                } else {
                    $diagnostic = 'verification_response_invalid';
                    $this->validateAsset($result, $manifest);
                    $manifest['asset_id'] = $result['asset_id'];
                    $manifest['state'] = 'verified';
                    $manifest['last_observation'] = 'owned_asset_present';
                }
                $diagnostic = 'verification_result_save_failed';
                $this->save($root, $manifest);
            } else {
                $diagnostic = 'deletion_state_invalid';
                if (! in_array($manifest['state'], ['verified', 'delete_attempted', 'deleted'], true) || empty($manifest['asset_id'])) {
                    throw new \RuntimeException;
                }
                // Inspect the exact ID before every deletion, including retries:
                // never delete a replacement asset with a different asset_id.
                $diagnostic = 'configuration_preflight_failed';
                $client->preflight();
                $diagnostic = 'deletion_inspection_failed';
                $current = $client->inspect($manifest['public_id']);
                $diagnostic = 'deletion_identity_invalid';
                if ($current !== null) { $this->validateAsset($current, $manifest); }
                $manifest['state'] = 'delete_attempted';
                $diagnostic = 'deletion_intent_save_failed';
                $this->save($root, $manifest);
                $diagnostic = 'deletion_remote_outcome_uncertain';
                $result = $client->destroy($manifest['public_id']);
                $diagnostic = 'deletion_response_invalid';
                if (! in_array($result['result'] ?? null, ['ok', 'not found'], true)) { throw new \RuntimeException; }
                $manifest['state'] = 'deleted';
                $diagnostic = 'deletion_result_save_failed';
                $this->save($root, $manifest);
            }
            $this->info('Phase completed. Manifest state: '.$manifest['state']);
            if (isset($manifest['last_observation'])) { $this->line('Observation: '.$manifest['last_observation']); }
            return self::SUCCESS;
        } catch (\Throwable $error) {
            // No SDK exceptions, traces, URLs or credential-bearing configuration.
            if ($error instanceof \App\Services\Photos\HomologationDiagnostic) {
                $diagnostic = $error->diagnostic;
            } elseif ($error instanceof \Cloudinary\Api\Exception\ApiError) {
                // A response was received; don't confuse provider rejection with
                // a connection failure or expose the SDK's message/body.
                $diagnostic = match ($diagnostic) {
                    'upload_remote_outcome_uncertain' => 'upload_remote_rejected',
                    'verification_remote_failed' => 'verification_remote_rejected',
                    'deletion_inspection_failed' => 'deletion_inspection_rejected',
                    'deletion_remote_outcome_uncertain' => 'deletion_remote_rejected',
                    default => $diagnostic,
                };
            }
            $this->line('Diagnostic: '.$diagnostic);
            $this->error('Operation not confirmed. Manifest preserved; inspect its state before retrying. No automatic cleanup.');
            return self::FAILURE;
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function validateManifest(array $m, string $run): void
    {
        if (($m['version'] ?? null) !== 1 || ($m['run'] ?? null) !== $run
            || ! preg_match('/^[a-f0-9]{32}$/D', $m['resource'] ?? '')
            || ($m['public_id'] ?? null) !== 'adoptplace/pets/homologation-'.$run.'/'.$m['resource']
            || ($m['asset_folder'] ?? null) !== 'adoptplace-homologation/'.$run
            || ($m['cloud_name'] ?? null) !== getenv('CLOUDINARY_CLOUD_NAME')
            || ! in_array($m['folder_mode'] ?? null, ['dynamic', 'fixed'], true)) {
            throw new \RuntimeException;
        }
    }

    private function validateAsset(array $asset, array $m): void
    {
        if (($asset['public_id'] ?? null) !== $m['public_id']
            || ! is_string($asset['asset_id'] ?? null) || $asset['asset_id'] === ''
            || (isset($m['asset_id']) && $asset['asset_id'] !== $m['asset_id'])
            || ($asset['resource_type'] ?? null) !== 'image' || ($asset['type'] ?? null) !== 'upload'
            || ($asset['context']['custom']['adoptplace_homologation_run'] ?? null) !== $m['run']
            || ($m['folder_mode'] === 'dynamic' && ($asset['asset_folder'] ?? null) !== $m['asset_folder'])
            || filter_var($asset['secure_url'] ?? '', FILTER_VALIDATE_URL) === false
            || parse_url($asset['secure_url'], PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException;
        }
    }

    private function save(string $root, array $manifest): void
    {
        $manifest['updated_at'] = gmdate(DATE_ATOM);
        $temp = tempnam($root, '.manifest-');
        if ($temp === false) { throw new \RuntimeException; }
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false
                || ! rename($temp, $root.'/'.$manifest['run'].'.json')) {
                throw new \RuntimeException;
            }
        } finally {
            if (is_file($temp)) { unlink($temp); }
        }
    }
}
