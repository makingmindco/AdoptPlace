<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case OrganizationOwner = 'organization_owner';
    case OrganizationMember = 'organization_member';
}
