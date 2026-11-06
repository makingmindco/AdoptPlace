<?php

namespace App\Enums;

enum PetStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case AdoptionInProgress = 'adoption_in_progress';
    case Adopted = 'adopted';
    case Archived = 'archived';
}
