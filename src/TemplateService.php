<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class TemplateService
{
    public function __construct(private Database $db,private SshClient $ssh) {}
    public function node(array $actor,int $id): array
    {
        if(!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn())throw new RuntimeException('Adminisztrátori jogosultság szükséges.');
        return $this->db->query('SELECT * FROM nodes WHERE id=?',[$id])->fetch() ?: throw new RuntimeException('A Linux-gép nem található.');
    }
    public function catalog(array $actor,int $id): array { return $this->ssh->templates($this->node($actor,$id),'catalog'); }
    public function delete(array $actor,int $id,string $key): array {
        if ($key==='' || strlen($key)>200) throw new RuntimeException('Érvénytelen template-azonosító.');
        return $this->ssh->templates($this->node($actor,$id),'delete',$key);
    }
    public function sync(array $actor,int $id): array { return $this->ssh->templates($this->node($actor,$id),'sync'); }
}
