<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Utility;

enum ErrorSeverity: string
{
    case NOTICE = 'notice';
    case WARN = 'warn';
    case FATAL = 'fatal';
}
