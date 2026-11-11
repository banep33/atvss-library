<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        /*
        |----------------------------------------------------------------------
        | Imenovani nalozi kod poznatih servisa (Gmail, Outlook)
        |----------------------------------------------------------------------
        |
        | Isti „smtp" transport kao gore, ali sa unapred upisanim adresama
        | servera — da se u .env drže samo korisničko ime i lozinka. Koji se
        | koristi bira MAIL_MAILER (npr. MAIL_MAILER=gmail), a `failover`
        | ispod ume da ih redom proba.
        |
        | `scheme` se namerno ostavlja prazan: Symfony tada sam bira zaštitu
        | prema portu — 587 znači STARTTLS, 465 znači TLS od prvog bajta.
        | Vrednost se popunjava („smtp" ili „smtps") samo ako neki server
        | traži da mu se to izričito kaže.
        |
        | Lozinka NIJE lozinka naloga:
        |   - Gmail traži „App password" (nalog mora imati uključenu potvrdu
        |     u dva koraka), obična lozinka se odbija.
        |   - Outlook / Microsoft 365 traži da u zakupu (tenant) bude dozvoljen
        |     SMTP AUTH; Microsoft ga postepeno gasi za osnovnu prijavu, pa
        |     proveri da li ti je još uključen pre nego što se osloniš na njega.
        |
        */

        'gmail' => [
            'transport' => 'smtp',
            'scheme' => env('GMAIL_SCHEME'),
            'host' => env('GMAIL_HOST', 'smtp.gmail.com'),
            'port' => env('GMAIL_PORT', 587),
            'username' => env('GMAIL_USERNAME'),
            'password' => env('GMAIL_PASSWORD'),
            'timeout' => env('MAIL_TIMEOUT', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'outlook' => [
            'transport' => 'smtp',
            'scheme' => env('OUTLOOK_SCHEME'),
            'host' => env('OUTLOOK_HOST', 'smtp.office365.com'),
            'port' => env('OUTLOOK_PORT', 587),
            'username' => env('OUTLOOK_USERNAME'),
            'password' => env('OUTLOOK_PASSWORD'),
            'timeout' => env('MAIL_TIMEOUT', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        /*
        | Redom proba navedene naloge dok jedan ne uspe — MAIL_MAILER=failover
        | znači „pošalji preko Gmail-a, a ako on ne prođe, probaj Outlook".
        | Spisak se zadaje u .env, zarezima, npr:
        |     MAIL_FAILOVER_MAILERS=gmail,outlook,log
        | Poslednji „log" je mreža za pad: poruka se tada bar upiše u
        | storage/logs/laravel.log umesto da nestane.
        */
        'failover' => [
            'transport' => 'failover',
            'mailers' => array_map(
                'trim',
                explode(',', (string) env('MAIL_FAILOVER_MAILERS', 'gmail,outlook,log'))
            ),
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];
