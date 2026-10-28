<?php
declare(strict_types=1);
namespace GameDock;
use Smarty\Smarty;
use RuntimeException;
final class SmartyView
{
    private Smarty $smarty;
    public function __construct(string $root)
    {
        $compile = $root . '/var/smarty/compile';
        if (!is_dir($compile) && !mkdir($compile, 0770, true) && !is_dir($compile)) {
            throw new RuntimeException('Nem hozható létre a Smarty fordítási könyvtára.');
        }
        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir($root . '/templates');
        $this->smarty->setCompileDir($compile);
        $this->smarty->escape_html = true;
        $this->smarty->setCaching(Smarty::CACHING_OFF);
    }
    public function render(string $template, array $data): void
    {
        $this->smarty->assign($data);
        $this->smarty->display($template);
    }
}
