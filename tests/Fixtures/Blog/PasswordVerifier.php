<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog;

interface PasswordVerifier
{

    public function isPasswordValid(string $password): bool;

}
