<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class ReportBodyFormatter
{
    public function format(string $body): HtmlString
    {
        return new HtmlString($this->formatSegment($body));
    }

    private function formatSegment(string $text): string
    {
        $formatted = '';
        $offset = 0;
        $styles = [
            '_#' => 'report-format-caption',
            '_##' => 'report-format-bold',
            '_###' => 'report-format-uppercase',
        ];

        while (preg_match('/_(#{1,3})/', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $marker = '_'.$match[1][0];
            $markerOffset = $match[0][1];
            $markerEnd = $markerOffset + strlen($marker);
            $formatted .= e(substr($text, $offset, $markerOffset - $offset));

            if ($closingOffset = $this->closingMarkerOffset($text, $marker, $markerEnd)) {
                $inner = substr($text, $markerEnd, $closingOffset - $markerEnd);
                $formatted .= '<span class="'.$styles[$marker].'">'.$this->formatSegment($inner).'</span>';
                $offset = $closingOffset + strlen($marker);
            } else {
                $formatted .= e($marker);
                $offset = $markerEnd;
            }
        }

        return $formatted.e(substr($text, $offset));
    }

    private function closingMarkerOffset(string $text, string $marker, int $offset): ?int
    {
        while (preg_match('/_(#{1,3})/', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $candidate = '_'.$match[1][0];
            $candidateOffset = $match[0][1];
            if ($candidate === $marker) {
                return $candidateOffset;
            }

            $offset = $candidateOffset + strlen($candidate);
        }

        return null;
    }
}
