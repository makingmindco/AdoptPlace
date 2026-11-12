<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\V1\Admin\PetController as AdminPetController;
use App\Http\Controllers\Api\V1\AdoptionRequestController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationPetController;
use App\Http\Controllers\Api\V1\PublicOrganizationController;
use App\Http\Controllers\Api\V1\PublicPetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'adoptplace-api',
]));

Route::prefix('v1')->group(function () {
    Route::get('/address/cep/{cep}', [AddressController::class, 'show'])->middleware('throttle:30,1');
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    Route::get('/pets', [PublicPetController::class, 'index']);
    Route::get('/pets/{slug}', [PublicPetController::class, 'show']);
    Route::get('/organizations/{slug}', [PublicOrganizationController::class, 'show']);
    Route::post('/pets/{pet}/adoption-requests', [AdoptionRequestController::class, 'store'])
        ->middleware('throttle:adoption-requests');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/email/verification-notification', function (Request $request) {
            if ($request->user()->hasVerifiedEmail()) {
                return response()->json(['message' => 'E-mail já verificado.']);
            }

            $request->user()->sendEmailVerificationNotification();

            return response()->json(['message' => 'Link de verificação enviado.']);
        })->middleware('throttle:6,1');

        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::patch('/organization', [OrganizationController::class, 'update']);

        Route::middleware('organization.approved')->group(function () {
            Route::apiResource('/organization/pets', OrganizationPetController::class)
                ->parameters(['pets' => 'pet']);
            Route::post('/organization/pets/{pet}/publish', [OrganizationPetController::class, 'publish']);
            Route::post('/organization/pets/{pet}/mark-adopted', [OrganizationPetController::class, 'markAdopted']);
            Route::post('/organization/pets/{pet}/photos', [OrganizationPetController::class, 'uploadPhoto']);
            Route::delete('/organization/pets/{pet}/photos/{photoId}', [OrganizationPetController::class, 'deletePhoto']);

            Route::get('/organization/adoption-requests', [AdoptionRequestController::class, 'index']);
            Route::get('/organization/adoption-requests/{adoptionRequest}', [AdoptionRequestController::class, 'show']);
            Route::patch('/organization/adoption-requests/{adoptionRequest}', [AdoptionRequestController::class, 'update']);
        });

        Route::prefix('admin')->middleware('admin')->group(function () {
            Route::get('/organizations', [AdminOrganizationController::class, 'index']);
            Route::patch('/organizations/{organization}/status', [AdminOrganizationController::class, 'updateStatus']);
            Route::get('/pets', [AdminPetController::class, 'index']);
            Route::patch('/pets/{pet}/status', [AdminPetController::class, 'moderate']);
            Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
        });
    });
});
