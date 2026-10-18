<?php

namespace EduLazaro\Laracrate\Exceptions;

/**
 * A file refused by the collection or slot rules.
 *
 * Extends InvalidArgumentException, which is what these checks always threw, so code
 * catching that keeps working. The message stays in English for logs; `reason` and
 * `params` let an endpoint show the user a translated text
 * (`laracrate::uploader.rejected.{reason}`).
 */
class UploadRejected extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }

    /** The text for the user, in the app locale. */
    public function userMessage(): string
    {
        $key = "laracrate::uploader.rejected.{$this->reason}";
        $text = __($key, $this->params);

        return $text === $key ? $this->getMessage() : $text;
    }
}
