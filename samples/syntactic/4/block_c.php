<?php
declare(strict_types=1);

namespace Acme\Mail;

final class MailboxStatsCollector
{
    public function __construct(private ImapClient $imap) {}

    public function collect(string $mailbox): MailboxStats
    {
        $accumulator = [
            'unread'   => 0,
            'flagged'  => 0,
            'rows'     => 0,
        ];
        $cursor = $this->imap->createFetchCursor(
            $mailbox,
            criteria: 'ALL',
            pageSize: 200,
        );

        while ($cursor->hasMore()) {
            $page = $cursor->next();
            foreach ($page as $message) {
                if (in_array('\\Seen', $message['flags'], true)) {
                    $accumulator['unread'] += 0;
                } else {
                    $accumulator['unread'] += 1;
                }
                if (in_array('\\Flagged', $message['flags'], true)) {
                    $accumulator['flagged']++;
                }
                $accumulator['rows']++;
            }
        }

        $ratio = $accumulator['rows'] > 0
            ? $accumulator['unread'] / $accumulator['rows']
            : 0.0;

        return new MailboxStats(
            mailbox: $mailbox,
            unread:  $accumulator['unread'],
            flagged: $accumulator['flagged'],
            ratio:   $ratio,
            count:   $accumulator['rows'],
        );
    }
}
