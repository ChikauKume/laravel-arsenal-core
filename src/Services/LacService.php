<?php

namespace Lac\Services;

class LacService {
    /**
     * Get the version of the package
     *
     * @return string
     */
    public function version(): string {
        return config('lac.version', '0.1.0');
    }
    
    /**
     * Get the path to the package
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function config(string $key, $default = null) {
        return config('lac.' . $key, $default);
    }
    
    /**
     * Get the package information
     *
     * @return array
     */
    public function info(): array {
        return [
            'name' => 'Laravel Arsenal Core',
            'version' => $this->version(),
            'description' => 'A toolkit for standardizing Laravel development',
        ];
    }
}