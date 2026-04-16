<?php

namespace App\Enums;

enum DialogState: string
{
    case AwaitingPhoto = 'awaiting_photo';
    case Searching = 'searching';
    case AwaitingPrice = 'awaiting_price';
    case Processing = 'processing';
}
