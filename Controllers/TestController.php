<?php

namespace App\Controllers;

use App\Models\TestProjects;
use PDO;
use PDOException;

class TestController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function setSearchPath(): void
    {
        // Set search_path to public schema (required because isolated role has restricted search_path)
        // Using string concatenation to avoid C# string interpolation issues with $user
        $dollarSign = '$';
        $query = 'SET search_path = public, "' . $dollarSign . 'user"';
        $this->db->exec($query);
    }

    public function getAll(): array
{
    // This will raise a DivisionByZeroError
    $result = 1 / 0;
    
    // Set search_path to public schema (required because isolated role has restricted search_path)
    $this->setSearchPath();
    $stmt = $this->db->query('SELECT "Id", "Name" FROM "TestProjects" ORDER BY "Id"');
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $projects = [];
    foreach ($results as $row) {
        $projects[] = [
            'Id' => (int)$row['Id'],
            'Name' => $row['Name']
        ];
    }
    return $projects;
}


    public function getById(int $id): ?array
    {
        // Set search_path to public schema (required because isolated role has restricted search_path)
        $this->setSearchPath();
        $stmt = $this->db->prepare('SELECT "Id", "Name" FROM "TestProjects" WHERE "Id" = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        return [
            'Id' => (int)$row['Id'],
            'Name' => $row['Name']
        ];
        // Do NOT catch generic Exception - let it bubble up to global exception handler
    }

    public function create(array $data): array
    {
        // Set search_path to public schema (required because isolated role has restricted search_path)
        $this->setSearchPath();
        $stmt = $this->db->prepare('INSERT INTO "TestProjects" ("Name") VALUES (:name) RETURNING "Id", "Name"');
        $stmt->execute(['name' => $data['name']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'Id' => (int)$row['Id'],
            'Name' => $row['Name']
        ];
        // Do NOT catch generic Exception - let it bubble up to global exception handler
    }

    public function update(int $id, array $data): ?array
    {
        // Set search_path to public schema (required because isolated role has restricted search_path)
        $this->setSearchPath();
        $stmt = $this->db->prepare('UPDATE "TestProjects" SET "Name" = :name WHERE "Id" = :id RETURNING "Id", "Name"');
        $stmt->execute(['id' => $id, 'name' => $data['name']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        return [
            'Id' => (int)$row['Id'],
            'Name' => $row['Name']
        ];
        // Do NOT catch generic Exception - let it bubble up to global exception handler
    }

    public function delete(int $id): bool
    {
        // Set search_path to public schema (required because isolated role has restricted search_path)
        $this->setSearchPath();
        $stmt = $this->db->prepare('DELETE FROM "TestProjects" WHERE "Id" = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
        // Do NOT catch generic Exception - let it bubble up to global exception handler
    }
}
