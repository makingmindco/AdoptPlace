<?php

namespace Database\Seeders;

use App\Enums\OrganizationStatus;
use App\Enums\PetStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::updateOrCreate(
            ['slug' => 'amigos-de-patas'],
            [
                'name' => 'Amigos de Patas',
                'type' => 'ngo',
                'description' => 'Organização demonstrativa do AdoptPlace.',
                'city' => 'São Paulo',
                'state' => 'SP',
                'email' => 'ong@adoptplace.local',
                'verification_status' => OrganizationStatus::Approved,
                'contact_publicly_visible' => true,
                'approved_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'ong@adoptplace.local'],
            [
                'name' => 'Equipe Amigos de Patas',
                'password' => 'Adoptplace123',
                'role' => UserRole::OrganizationOwner,
                'organization_id' => $organization->id,
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        $pets = [
            ['Luna', 'dog', 'Sem raça definida', 'female', 'medium', '2 anos', ['carinhosa', 'sociável'], 'Doce e companheira, Luna adora passeios tranquilos e ficar perto de quem ama.'],
            ['Theo', 'cat', 'Sem raça definida', 'male', 'small', '3 anos', ['curioso', 'tranquilo'], 'Theo é observador, afetuoso e adora encontrar um cantinho ensolarado para descansar.'],
            ['Pipoca', 'dog', 'Sem raça definida', 'female', 'small', '1 ano', ['brincalhona', 'alegre'], 'Pipoca é cheia de energia e transforma qualquer momento em uma oportunidade para brincar.'],
            ['Thor', 'dog', 'Sem raça definida', 'male', 'large', '4 anos', ['calmo', 'protetor'], 'Apesar do porte grande, Thor é gentil, equilibrado e muito cuidadoso com as pessoas.'],
            ['Amora', 'cat', 'Sem raça definida', 'female', 'small', '4 anos', ['gentil', 'independente'], 'Amora gosta de carinho no seu tempo e será uma ótima companhia para uma casa tranquila.'],
            ['Bento', 'dog', 'Terrier mestiço', 'male', 'small', '3 anos', ['divertido', 'esperto'], 'Bento tem uma personalidade cativante, aprende rápido e está sempre pronto para interagir.'],
            ['Mel', 'cat', 'Sem raça definida', 'female', 'small', '1 ano', ['serena', 'carinhosa'], 'Mel é delicada, sociável e se adapta bem a ambientes acolhedores e seguros.'],
            ['Chico', 'dog', 'Sem raça definida', 'male', 'large', '9 anos', ['dócil', 'companheiro'], 'Chico é um senhor muito amoroso que procura uma família para viver sua melhor fase com conforto.'],
            ['Nina', 'cat', 'Sem raça definida', 'female', 'small', '2 anos', ['atenta', 'afetuosa'], 'Nina gosta de explorar, observar a rotina da casa e depois pedir carinho bem pertinho.'],
            ['Zeca', 'dog', 'Sem raça definida', 'male', 'medium', '1 ano', ['animado', 'amigável'], 'Zeca é jovem, inteligente e combina com uma família disposta a brincar e ensinar com paciência.'],
        ];

        foreach ($pets as [$name, $species, $breed, $sex, $size, $age, $personality, $description]) {
            $slug = Str::slug($name);
            $image = "/demo-pets/{$slug}.png";

            Pet::updateOrCreate(
                ['slug' => $slug],
                [
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'species' => $species,
                    'breed' => $breed,
                    'sex' => $sex,
                    'size' => $size,
                    'approximate_age' => $age,
                    'description' => $description,
                    'personality' => $personality,
                    'health' => ['vaccinated' => true, 'neutered' => true, 'dewormed' => true],
                    'location' => ['city' => 'São Paulo', 'state' => 'SP'],
                    'photos' => [['id' => "demo-{$slug}", 'url' => $image, 'path' => null]],
                    'cover_photo' => $image,
                    'status' => PetStatus::Published,
                    'published_at' => now(),
                    'adopted_at' => null,
                ],
            );
        }
    }
}
