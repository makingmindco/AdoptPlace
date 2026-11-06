<?php

namespace App\Enums;

enum AdoptionRequestStatus: string
{
    case New = 'new';
    case Reviewing = 'reviewing';
    case Contacted = 'contacted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
