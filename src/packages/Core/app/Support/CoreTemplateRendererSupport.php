<?php

namespace Packages\Core\Support;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class CoreTemplateRendererSupport
{
    private Filesystem $files;
    private string $templatesBasePath;
    private Command $command;

    public function __construct(Filesystem $files, string $templatesBasePath, Command $command)
    {
        $this->files = $files;
        $this->templatesBasePath = rtrim($templatesBasePath, DIRECTORY_SEPARATOR);
        $this->command = $command;
    }

    /**
     * @param array<string, string> $variables
     */
    public function write(string $targetPath, string $templateName, array $variables = []): void
    {
        $content = $this->render($templateName, $variables);
        $this->files->put($targetPath, $content . PHP_EOL);
    }

    /**
     * @param array<string, string> $variables
     */
    public function render(string $templateName, array $variables = []): string
    {
        $templatePath = $this->templatePath($templateName);

        if (!$this->files->exists($templatePath)) {
            $this->command->fail("Template not found: {$templatePath}");
        }

        $template = $this->files->get($templatePath);

        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return $template;
    }

    private function templatePath(string $templateName): string
    {
        return $this->templatesBasePath . DIRECTORY_SEPARATOR . $templateName;
    }
}
