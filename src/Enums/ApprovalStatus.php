<?php

namespace Azeem\ApprovalWorkflow\Enums;

enum ApprovalStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SKIPPED = 'skipped';
    case RETURNED = 'returned';
}
