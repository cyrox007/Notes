<?php
namespace App\Helpers;

class CryptMethods {
    public static function createHashFromPassword(string $password): string {
        // Get unique key from environment variable
        $uniqueKey = getenv('UNIQUE_KEY');
        
        // Create a random salt
        $salt = bin2hex(random_bytes(16));
        
        // Create a base hash using bcrypt
        $bcryptHash = password_hash($password, PASSWORD_BCRYPT);
        
        // Combine bcrypt hash, unique key, and salt
        $combined = $bcryptHash . $uniqueKey . $salt;
        
        // Perform multiple rounds of SHA256 hashing
        $hash = hash('sha256', $combined);
        for ($i = 0; $i < 1000; $i++) {
            $hash = hash('sha256', $hash);
        }
        
        // Encode final hash with the bcrypt hash and salt
        // Storing bcrypt hash for verification purpose
        return base64_encode($bcryptHash . ':' . $salt . ':' . $hash);
    }

    public static function verifyPassword(string $password, string $storedHash): bool {
        // Get unique key from environment variable
        $uniqueKey = getenv('UNIQUE_KEY');
    
        // Decode the stored hash and extract the bcrypt hash, salt, and final hash
        $decodedHash = base64_decode($storedHash);
        list($storedBcryptHash, $salt, $storedFinalHash) = explode(':', $decodedHash);
    
        // Re-create the bcrypt hash using the stored bcrypt hash
        // bcrypt always generates a new hash, so we use password_verify instead
        if (password_verify($password, $storedBcryptHash)) {
            // Combine bcrypt hash, unique key, and salt
            $combined = $storedBcryptHash . $uniqueKey . $salt;
    
            // Perform the same multiple rounds of SHA256 hashing
            $inputHash = hash('sha256', $combined);
            for ($i = 0; $i < 1000; $i++) {
                $inputHash = hash('sha256', $inputHash);
            }
    
            // Compare the computed final hash with the stored final hash
            return hash_equals($inputHash, $storedFinalHash);
        }
    
        // If bcrypt verification fails, return false
        return false;
    }
}