# Security Audit Report

**Date**: 2026-02-09  
**Repository**: agent-skills (wordpress-trac plugin)  
**Auditor**: GitHub Copilot Security Agent

## Executive Summary

This security audit identified and fixed **7 security vulnerabilities** and **3 correctness issues** across 4 PHP scripts in the repository. All identified issues have been remediated.

## Security Vulnerabilities Fixed

### 1. Missing SSL/TLS Verification (CRITICAL)
**Severity**: High  
**Files Affected**: All 4 PHP scripts  
**Issue**: curl requests were made without SSL certificate verification, making them vulnerable to Man-in-the-Middle (MITM) attacks.

**Fix**: Added the following curl options to all HTTP requests:
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
```

### 2. Insufficient SSRF Protection (HIGH)
**Severity**: High  
**File**: `scripts/search.php`  
**Issue**: URL validation used `strpos()` which is vulnerable to bypasses. A malicious user could potentially craft URLs to access unintended resources.

**Fix**: Replaced string matching with proper URL parsing and strict validation:
```php
$parsed = parse_url($url);
if ($parsed === false 
    || !isset($parsed['scheme']) 
    || $parsed['scheme'] !== 'https'
    || !isset($parsed['host'])
    || $parsed['host'] !== 'core.trac.wordpress.org'
    || !isset($parsed['path'])
    || $parsed['path'] !== '/query'
) {
    fwrite(STDERR, "Error: URL must be https://core.trac.wordpress.org/query\n");
    exit(1);
}
```

### 3. Missing Request Timeouts (MEDIUM)
**Severity**: Medium  
**Files Affected**: All 4 PHP scripts  
**Issue**: curl requests had no timeout settings, potentially causing the process to hang indefinitely.

**Fix**: Added connection and execution timeouts:
```php
curl_setopt($ch, CURLOPT_TIMEOUT, 30);           // 30 second total timeout
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);   // 10 second connection timeout
```

### 4. Insufficient Input Validation (MEDIUM)
**Severity**: Medium  
**Files Affected**: `ticket.php`, `changeset.php`, `ticket-discussion.php`  
**Issue**: Ticket and changeset numbers were validated as numeric but had no maximum length restriction, potentially allowing extremely large numbers.

**Fix**: Added length validation:
```php
if (!ctype_digit($ticket_num) || strlen($ticket_num) > 10) {
    fwrite(STDERR, "Error: Invalid ticket number: {$ticket_num}\n");
    exit(1);
}
```

### 5. Markdown Injection in Link Text (MEDIUM)
**Severity**: Medium  
**Files Affected**: `changeset.php`, `ticket-discussion.php`  
**Issue**: Special markdown characters in link text were not escaped, potentially breaking markdown formatting or enabling injection attacks.

**Fix**: Added proper escaping for markdown special characters:
```php
$text = str_replace(['\\', '[', ']', '(', ')'], ['\\\\', '\\[', '\\]', '\\(', '\\)'], $text);
```

### 6. Markdown Injection in URLs (MEDIUM)
**Severity**: Medium  
**Files Affected**: `changeset.php`, `ticket-discussion.php`  
**Issue**: Parentheses in URLs were not escaped, potentially breaking markdown link syntax.

**Fix**: URL-encoded parentheses:
```php
$href = str_replace(['(', ')'], ['%28', '%29'], $href);
```

### 7. Markdown Injection in Code Blocks (LOW)
**Severity**: Low  
**Files Affected**: `changeset.php`, `ticket-discussion.php`  
**Issue**: Backticks in inline code were not escaped.

**Fix**: Added backtick escaping:
```php
$code = str_replace('`', '\\`', $child->textContent);
```

## Correctness Issues Fixed

### 1. Improper Resource Cleanup
**Files Affected**: `ticket.php`, `search.php`  
**Issue**: curl handles were freed using `unset($ch)` instead of `curl_close($ch)`.

**Fix**: Changed to proper cleanup:
```php
curl_close($ch);
```

### 2. Missing curl Error Handling
**Files Affected**: All 4 PHP scripts  
**Issue**: Only HTTP status codes were checked; curl execution failures were not detected.

**Fix**: Added curl error detection:
```php
$result = curl_exec($ch);
if ($result === false) {
    fwrite(STDERR, "Error: Failed to fetch data\n");
    exit(1);
}
```

### 3. Incomplete Markdown Table Escaping
**File**: `search.php`  
**Issue**: Only pipe characters were escaped in table cells, not backslashes.

**Fix**: Added backslash escaping:
```php
return str_replace(['\\', '|'], ['\\\\', '\\|'], $value);
```

## Risk Assessment

All identified vulnerabilities have been remediated. The remaining security posture is:

- ✅ **Input Validation**: Strong - numeric validation with length limits
- ✅ **SSRF Protection**: Strong - strict URL validation with parse_url()
- ✅ **SSL/TLS**: Strong - certificate verification enabled
- ✅ **Output Encoding**: Strong - markdown special characters properly escaped
- ✅ **Resource Management**: Good - proper cleanup of resources
- ✅ **Error Handling**: Good - curl errors properly detected

## Recommendations

1. **Consider adding rate limiting** if these scripts will be exposed to untrusted users
2. **Consider adding logging** for security-relevant events (failed requests, validation failures)
3. **Regular dependency updates** - Keep PHP version current for security patches
4. **Code signing** - Consider signing the PHP scripts to ensure integrity

## Testing Performed

All changes were validated with:
- ✅ PHP syntax validation (`php -l`)
- ✅ Input validation testing (invalid ticket numbers, malformed URLs)
- ✅ Edge case testing (oversized input, special characters)
- ✅ Manual code review for additional vulnerabilities

## Conclusion

The repository has been successfully audited and all identified security vulnerabilities have been fixed. The code now follows security best practices for:
- Secure HTTP communication
- Input validation
- Output encoding
- Resource management
- Error handling

No additional security issues were identified during this audit.
