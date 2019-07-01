<?php
namespace Tesla\Chrome2Pdf;

use RuntimeException;
use InvalidArgumentException;
use ChromeDevtoolsProtocol\Context;
use ChromeDevtoolsProtocol\Instance\Launcher;
use ChromeDevtoolsProtocol\Model\Page\NavigateRequest;
use ChromeDevtoolsProtocol\Model\Page\PrintToPDFRequest;

class Chrome2Pdf
{
    use HasPdfAttributes;

    private $ctx;

    private $launcher;

    private $tmpFolderPath = null;

    private $chromeExecutablePath = '/opt/google/chrome/chrome';

    public function __construct()
    {
        $this->ctx = Context::withTimeout(Context::background(), 30);
        $this->launcher = new Launcher();
    }

    public function setTempFolder(string $path)
    {
        $this->tmpFolderPath = $path;

        return $this;
    }

    public function getTempFolder()
    {
        if ($this->tmpFolderPath === null) {
            return sys_get_temp_dir();
        }

        return $this->tmpFolderPath;
    }

    public function getBrowserLauncher()
    {
        return $this->launcher;
    }

    public function setBrowserLauncher($launcher)
    {
        $this->launcher = $launcher;

        return $this;
    }

    public function getContext()
    {
        return $this->ctx;
    }

    public function setContext($ctx)
    {
        $this->ctx = $ctx;

        return $this;
    }

    public function getChromeExecutablePath()
    {
        return $this->chromeExecutablePath;
    }

    public function setChromeExecutablePath($chromeExecutablePath)
    {
        $this->chromeExecutablePath = $chromeExecutablePath;

        return $this;
    }

    public function pdf()
    {
        $launcher = $this->getBrowserLauncher();
        $launcher->setExecutable($this->getChromeExecutablePath());
        $ctx = $this->getContext();
        $instance = $launcher->launch($ctx);

        $filename = $this->writeTempFile();
        $pdfOptions = $this->getPDFOptions();

        try {
            $tab = $instance->open($ctx);
            $tab->activate($ctx);

            $devtools = $tab->devtools();
            try {
                $devtools->page()->enable($ctx);
                $devtools->page()->navigate($ctx, NavigateRequest::builder()->setUrl('file://' . $filename)->build());
                $devtools->page()->awaitLoadEventFired($ctx);

                $response = $devtools->page()->printToPDF($ctx, $pdfOptions);

                return base64_decode($response->data);

            } finally {
                $devtools->close();
            }

        } finally {
            $instance->close();
        }

        if (file_exists($filename)) {
            unlink($filename);
        }
    }

    protected function writeTempFile(): string
    {
        if (!$this->content) {
            throw new InvalidArgumentException('Missing content, set content by calling "setContent($html)" method');
        }

        $filepath = rtrim($this->getTempFolder(), DIRECTORY_SEPARATOR);

        if (!is_dir($filepath)) {
            if (false === @mkdir($filepath, 0777, true) && !is_dir($filepath)) {
                throw new RuntimeException(sprintf("Unable to create directory: %s\n", $filepath));
            }
        } elseif (!is_writable($filepath)) {
            throw new RuntimeException(sprintf("Unable to write in directory: %s\n", $filepath));
        }

        $filename = $filepath . DIRECTORY_SEPARATOR . uniqid('chrome2pdf_', true) . '.html';

        file_put_contents($filename, $this->content);

        return $filename;
    }

    private function getPDFOptions()
    {
        $pdfOptions = PrintToPDFRequest::make();

        $pdfOptions->landscape = $this->orientation === 'landscape';
        $pdfOptions->marginTop = $this->margins['top'];
        $pdfOptions->marginRight = $this->margins['right'];
        $pdfOptions->marginBottom = $this->margins['bottom'];
        $pdfOptions->marginLeft = $this->margins['left'];
        $pdfOptions->preferCSSPageSize = $this->preferCSSPageSize;
        $pdfOptions->printBackground = $this->printBackground;

        if ($this->paperWidth) {
            $pdfOptions->paperWidth = $this->paperWidth;
        }

        if ($this->paperHeight) {
            $pdfOptions->paperHeight = $this->paperHeight;
        }

        if ($this->header || $this->footer) {
            $pdfOptions->displayHeaderFooter = true;
            $pdfOptions->headerTemplate = $this->header;
            $pdfOptions->footerTemplate = $this->footer;
        }

        return $pdfOptions;
    }
}
