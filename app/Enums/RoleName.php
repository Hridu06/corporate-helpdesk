<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Agent = 'agent';
    case Customer = 'customer';
}
