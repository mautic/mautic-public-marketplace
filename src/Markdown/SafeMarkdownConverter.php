<?php

namespace App\Markdown;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use Twig\Extra\Markdown\MarkdownInterface;

final class SafeMarkdownConverter implements MarkdownInterface
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $this->converter->getEnvironment()->addRenderer(Heading::class, new SafeHeadingRenderer(), 10);
    }

    public function convert(string $body): string
    {
        return (string) $this->converter->convert($body);
    }
}
