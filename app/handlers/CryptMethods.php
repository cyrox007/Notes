<?php
namespace App\Helpers;

class CryptMethods {
    /**
     * Create a hash from the user's password using a secure method.
     *
     * @param string $password The user's password
     * @return string The hashed password
     */
    public static function createHashFromPassword(string $password): string {
        // Create a random salt
        $salt = bin2hex(random_bytes(16));
        
        // Create a base hash using bcrypt
        $bcryptHash = password_hash($password, PASSWORD_BCRYPT);
        
        // Combine bcrypt hash and salt
        $combined = $bcryptHash . $salt;
        
        // Perform multiple rounds of SHA256 hashing
        $hash = hash('sha256', $combined);
        for ($i = 0; $i < 1000; $i++) {
            $hash = hash('sha256', $hash);
        }
        
        // Encode final hash with the salt
        return base64_encode($hash . ':' . $salt);
    }

    /**
     * Verify that a given password matches the stored hash.
     *
     * @param string $password The user's input password
     * @param string $storedHash The stored hashed password
     * @return bool Returns true if the password is correct, false otherwise
     */
    public static function verifyPassword(string $password, string $storedHash): bool {
        // Decode the stored hash and extract the salt
        $decodedHash = base64_decode($storedHash);
        list($hash, $salt) = explode(':', $decodedHash);
    
        // Create a base hash using bcrypt and the input password
        $bcryptHash = password_hash($password, PASSWORD_BCRYPT);
    
        // Combine bcrypt hash and salt
        $combined = $bcryptHash . $salt;
    
        // Perform the same multiple rounds of SHA256 hashing
        $inputHash = hash('sha256', $combined);
        for ($i = 0; $i < 1000; $i++) {
            $inputHash = hash('sha256', $inputHash);
        }
    
        // Compare the computed hash with the stored hash (excluding the salt)
        return hash_equals($inputHash, $hash);
    }
}