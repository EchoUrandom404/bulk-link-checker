# **AceLabs Bulk Link Checker for Craft CMS**

A link scanning utility for Craft CMS. It can scan issues with every link all in one go.

---

## Why This Plugin Exists

Real sites get messy over time:

- Editors add content for years  
- URLs change after redesigns  
- External sites disappear  
- Redirect chains accumulate  

Manually checking every link is impossible. However, Bulk Link Checker does this for you.

---

## Features

- Full multi-site scanning  
- Redirect‑chain tracking  
- Internal & external link checking  
- Advanced filtering
- Optional draft/disabled content scanning 
- See HTTP status codes & messages  
- Optional asset scanning (images/files)  
- Craft queue‑based background scanning  
- Utility UI with grouped results  
- Console command for CI/CD  

---

## Redirect Handling

This plugin automatically:

- Captures all redirect hops  
- Displays the full redirect chain  
- Tracks the final resolved URL  
- Distinguishes direct `200` vs `200 via redirect`

**Example:**

```
301 /old-url    → /new-url
301 /new-url    → /newer-url
200 /newer-url
```

---

## Installation

### Via Composer

```
composer require acelabs/craft-bulk-link-checker
```

Then enable the plugin in:

**Settings → Plugins**

---

## Control Panel Usage

- Select sites to scan  
- Choose internal / external / both link types  
- Apply ignore patterns  
- Scan pages and field content  
- View grouped results  
- Inspect redirect chains  
- Fix issues and rescan  

---

## Command‑Line Usage (Craft Console)

Run a scan:

```
php craft bulk-link-checker/scan/run
```

Perfect for automated workflows, CI pipelines, and local dev tools.

---

## CLI Options

### Sites
```
--sites=1,2
--sites=default,en
```
Accepts IDs **or** handles.

### Entry Scope
```
--entryScope=all        (default)
--entryScope=section
--entryScope=entryType
```

If using section/entryType:
```
--sections=3,7
--entryTypes=12,13
```

### Link Types
```
--linkMode=both         (default)
--linkMode=internal
--linkMode=external
```

### Additional Options
```
--includeDisabled=1
--checkContentLinks=0
--checkAssets=1
```

### Ignore Patterns
```
--ignorePatterns="google.com
facebook.com"
```

### Output Format
```
--format=text
--format=json
```
JSON is ideal for CI.

---

## Example CI Run

```
php craft bulk-link-checker/scan/run   --sites=default   --linkMode=external   --format=json
```

**Exit Codes:**

- `0` → All good  
- `1` → Broken links detected  

---

## Performance

It is possible to configure the amount of links checked at once with a config file to a maximum of 50 link requests concurrently, massively speeding up the process of checking each link. 

Paste the following return statement in `config/bulk-link-checker.php`
```
<?php
return [
    // How many URLs to hit in parallel (per entry / per pool)
    'concurrency' => 10,
];
```


Be sure to match this with what your server can handle as to avoid performance issues with loading pages as higher values can stress the CPU further. The more CPU threads, the better. 

Also be mindful of requesting many links from a single external domain as that may flag it as spam if you request too many. So use the ignore URL patterns feature to your advantage.



## Requirements

- Craft CMS **5.x+**
- PHP **8.0+**

---

## Reporting Issues

Please open an issue on the project repository.

---

## License

All rights reserved.
**© AceLabs 2025**
