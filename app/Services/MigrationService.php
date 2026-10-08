<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class MigrationService
{
    public function __construct(private readonly DB $db, private readonly string $basePath) {}

    public function run(): array
    {
        $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $files = glob($this->basePath . '/database/migrations/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied=[]; $skipped=[];
        foreach($files as $file){
            $filename=basename($file);
            if($this->db->fetchOne('SELECT id FROM migrations WHERE filename=:filename',['filename'=>$filename])){$skipped[]=$filename;continue;}
            $sql=file_get_contents($file);
            if($sql===false) throw new \RuntimeException('Cannot read migration: '.$filename);
            $this->db->transaction(function(DB $db)use($sql,$filename):void{
                $db->pdo()->exec($sql);
                $db->execute('INSERT INTO migrations(filename) VALUES(:filename)',['filename'=>$filename]);
            });
            $applied[]=$filename;
        }
        return ['applied'=>$applied,'skipped'=>$skipped];
    }
}
