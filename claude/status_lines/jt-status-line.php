#!/usr/bin/env php
<?php

/**
 * Log status line events to ~/.claude/logs/status_line.json.
 *
 * @param array $input_data The input data from Claude Code
 * @param string $status_line_output The generated status line
 * @param string|null $error_message Optional error message to log
 */
function log_status_line($input_data, $status_line_output, $error_message = null) {
    // Ensure ~/.claude/logs directory exists
    $log_dir = $_SERVER['HOME'] . '/.claude/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . '/status_line.json';

    // Read existing log data or initialize empty array
    $log_data = [];
    if (file_exists($log_file)) {
        $json_content = file_get_contents($log_file);
        $decoded = json_decode($json_content, true);
        if ($decoded !== null) {
            $log_data = $decoded;
        }
    }

    // Create log entry
    $log_entry = [
        'timestamp' => date('c'),
        'version' => 'php',
        'input_data' => $input_data,
        'status_line_output' => $status_line_output
    ];

    if ($error_message) {
        $log_entry['error'] = $error_message;
    }

    // Append the log entry
    $log_data[] = $log_entry;

    // Write back to file with formatting
    file_put_contents($log_file, json_encode($log_data, JSON_PRETTY_PRINT));
}

/**
 * Get the current git branch name.
 *
 * @return string|null The branch name or null if not in a git repo
 */
function get_git_branch() {
    $output = null;
    $return_var = null;
    exec('git rev-parse --abbrev-ref HEAD 2>/dev/null', $output, $return_var);

    if ($return_var === 0 && !empty($output)) {
        return trim($output[0]);
    }

    return null;
}

/**
 * Get git status indicator showing number of changed files.
 *
 * @return string Empty string or ±N format where N is number of changes
 */
function get_git_status() {
    $output = null;
    $return_var = null;
    exec('git status --porcelain 2>/dev/null', $output, $return_var);

    if ($return_var === 0 && !empty($output)) {
        return '±' . count($output);
    }

    return '';
}

/**
 * Get recent prompts from session data.
 *
 * @param string $session_id The Claude Code session ID
 * @param int $count Maximum number of prompts to return (default 3)
 * @return array [prompts_array, error_message] where prompts are in reverse chronological order
 */
function get_prompts($session_id, $count = 3) {
    $session_file = ".claude/data/sessions/{$session_id}.json";

    if (!file_exists($session_file)) {
        return [[], "Session file {$session_file} does not exist"];
    }

    $json_content = file_get_contents($session_file);
    $session_data = json_decode($json_content, true);

    if ($session_data === null) {
        return [[], "Error reading session file"];
    }

    $prompts = $session_data['prompts'] ?? [];
    if (!empty($prompts)) {
        // Return last $count prompts (most recent first)
        $recent_prompts = array_slice($prompts, -$count);
        return [array_reverse($recent_prompts), null];
    }

    return [[], "No prompts in session"];
}

/**
 * Generate the complete status line with model, directory, git, and prompt info.
 *
 * @param array $input_data Input data from Claude Code containing session and workspace info
 * @return string Formatted status line with ANSI color codes
 */
function generate_status_line($input_data) {
    $parts = [];

    // Model display name
    $model_info = $input_data['model'] ?? [];
    $model_name = $model_info['display_name'] ?? 'Claude';
    $parts[] = getMsg("[{$model_name}]", 'cyan');

    // Current directory
    $workspace = $input_data['workspace'] ?? [];
    $current_dir = $workspace['current_dir'] ?? '';
    if ($current_dir) {
        $dir_name = basename($current_dir);
        $parts[] = getMsg('📁 ', 'blue') . getMsg('/', 'dark_gray') . getMsg($dir_name, 'blue');
    }

    // Git branch and status
    $git_branch = get_git_branch();
    if ($git_branch) {
        $git_status = get_git_status();
        $git_info = "🌿 {$git_branch}";
        if ($git_status) {
            $git_info .= " {$git_status}";
        }
        $parts[] = getMsg($git_info, 'green');
    }

    // Recent prompts
    $session_id = $input_data['session_id'] ?? 'unknown';
    list($prompts, $error) = get_prompts($session_id, 3);

    if ($error || empty($prompts)) {
        // Show fallback message
        $parts[] = getMsg('💭 No recent prompts', 'dark_gray');
    } else {
        // Process each prompt with different opacity/colors
        foreach ($prompts as $index => $prompt) {
            // Format the prompt for status line
            $prompt = preg_replace('/\s+/', ' ', trim($prompt));

            // Different truncation lengths for different positions
            $max_lengths = [60, 35, 25]; // Current, previous, older
            $max_length = $max_lengths[$index] ?? 30;

            if (strlen($prompt) > $max_length) {
                $prompt = substr($prompt, 0, $max_length - 3) . '...';
            }

            if ($index === 0) {
                // Current prompt - full brightness with icon
                $prompt_lower = strtolower($prompt);
                if (strpos($prompt, '/') === 0) {
                    $prompt_color = 'yellow';
                    $icon = "⚡";
                } elseif (strpos($prompt, '?') !== false) {
                    $prompt_color = 'blue';
                    $icon = "❓";
                } elseif (preg_match('/\b(create|write|add|implement|build)\b/', $prompt_lower)) {
                    $prompt_color = 'light_green';
                    $icon = "💡";
                } elseif (preg_match('/\b(fix|debug|error|issue)\b/', $prompt_lower)) {
                    $prompt_color = 'red';
                    $icon = "🐛";
                } elseif (preg_match('/\b(refactor|improve|optimize)\b/', $prompt_lower)) {
                    $prompt_color = 'magenta';
                    $icon = "♻️";
                } else {
                    $prompt_color = 'white';
                    $icon = "💬";
                }
                $parts[] = $icon . ' ' . getMsg($prompt, $prompt_color);
            } elseif ($index === 1) {
                // Previous prompt - light gray
                $parts[] = getMsg($prompt, 'light_gray');
            } else {
                // Older prompt - dark gray
                $parts[] = getMsg($prompt, 'dark_gray');
            }
        }
    }

    return implode(' | ', $parts);
}

/**
 * Format text with optional color.
 *
 * @param string $text The text to format
 * @param string $color Color name from the color() function palette
 * @return string Formatted text with ANSI color codes
 */
function getMsg($text, $color = '') {
    if ($color) {
        $text = color($color) . $text . color('none');
    }

    return $text;
}

/**
 * Get a cli-formatted color indicator.
 *
 * @since  1.0.0
 *
 * @param  string $color Color to get.
 *
 * @return string
 */
function color($color) {
    $colors = [
        'red_bg'        => "\e[1;37;41m",
        'none'          => "\033[0m",
        'default'       => "\033[39m",
        'black'         => "\033[30m",
        'red'           => "\033[31m",
        'green'         => "\033[32m",
        'yellow'        => "\033[33m",
        'blue'          => "\033[34m",
        'magenta'       => "\033[35m",
        'cyan'          => "\033[36m",
        'light_gray'    => "\033[37m",
        'dark_gray'     => "\033[90m",
        'light_red'     => "\033[91m",
        'light_green'   => "\033[92m",
        'light_yellow'  => "\033[93m",
        'light_blue'    => "\033[94m",
        'light_magenta' => "\033[95m",
        'light_cyan'    => "\033[96m",
        'white'         => "\033[97m",
    ];

    return $color && isset($colors[$color])
        ? $colors[$color]
        : '';
}

/**
 * Main function that reads JSON from stdin and outputs formatted status line.
 */
function main() {
    try {
        // Read JSON input from stdin
        $input = stream_get_contents(STDIN);
        $input_data = json_decode($input, true);

        if ($input_data === null) {
            echo getMsg("💭 JSON decode error", 'red') . "\n";
            exit(0);
        }

        // Generate status line
        $status_line = generate_status_line($input_data);

        // Log the status line event
        log_status_line($input_data, $status_line);

        // Output the status line
        echo $status_line . "\n";

        exit(0);

    } catch (Exception $e) {
        // Handle any errors gracefully
        echo getMsg("💭 Error: " . $e->getMessage(), 'red') . "\n";
        exit(0);
    }
}

main();
?>