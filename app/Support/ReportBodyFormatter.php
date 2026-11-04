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

    public function plainText(string $body): string
    {
        return trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function sanitize(string $body): string
    {
        return app(AdminEmailFormatter::class)->sanitizeReportBody($body);
    }
}
