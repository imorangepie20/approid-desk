<?php

namespace App\Enums;

use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

enum NotificationDeliveryFailure: string
{
    case Timeout = 'timeout';
    case AttemptsExhausted = 'attempts_exhausted';
    case MailTransport = 'mail_transport';
    case Database = 'database';
    case Unexpected = 'unexpected';

    public function label(): string
    {
        return match ($this) {
            self::Timeout => '실행 시간 초과',
            self::AttemptsExhausted => '최대 시도 횟수 초과',
            self::MailTransport => '메일 전송 오류',
            self::Database => '데이터베이스 오류',
            self::Unexpected => '예상하지 못한 오류',
        };
    }

    public static function fromException(Throwable $exception): self
    {
        return match (true) {
            $exception instanceof TimeoutExceededException => self::Timeout,
            $exception instanceof MaxAttemptsExceededException => self::AttemptsExhausted,
            $exception instanceof TransportExceptionInterface => self::MailTransport,
            $exception instanceof QueryException => self::Database,
            default => self::Unexpected,
        };
    }
}
