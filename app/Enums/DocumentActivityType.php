<?php

namespace App\Enums;

enum DocumentActivityType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';
    case FileUploaded = 'file_uploaded';
    case FileRemoved = 'file_removed';
    case Comment = 'comment';
}
