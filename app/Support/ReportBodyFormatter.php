<?php

namespace App\Support;

use App\Services\AdminEmailFormatter;
use Illuminate\Support\HtmlString;

class ReportBodyFormatter
{
    public function format(string $body): HtmlString
    {
        return new HtmlString($this->sanitize($body));
    }

    public function sanitize(string $body): string
    {
        return app(AdminEmailFormatter::class)->sanitize($body);
    }
}
