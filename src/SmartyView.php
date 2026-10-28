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
        if (!is_dir($compile) && !@mkdir($compile, 0770, true) && !is_dir($compile)) {
            throw new SetupException('Nem hozható létre a var/smarty/compile könyvtár. A var könyvtárnak a PHP-folyamat számára írhatónak kell lennie.');
        }
        if (!is_writable($compile)) throw new SetupException('A var/smarty/compile könyvtár nem írható a PHP-folyamat számára. Ellenőrizd a tulajdonost és az írási jogosultságot; ne használj 777 jogosultságot.');
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
