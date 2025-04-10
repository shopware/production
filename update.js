#!/usr/bin/env node

import fs from 'fs';
import { execSync } from 'child_process';

const headers = {
  'User-Agent': 'shopware tag updater',
}

if (process.env.GITHUB_TOKEN) {
  headers['Authorization'] = `Bearer ${process.env.GITHUB_TOKEN}`;
}

/**
 * Update Shopware core to latest version
 * 
 * This script:
 * 1. Fetches all tags from Shopware core repository
 * 2. For each tag, checks if it already exists in the local git repository
 * 3. If not, updates composer.json, commits changes, tags, and resets to trunk
 */
async function update() {
  try {
    // Fetch tags from GitHub
    const response = await fetch('https://api.github.com/repos/shopware/core/tags?per_page=50', {
      headers
    });
    const tags = await response.json();

    // Process each tag
    for (const item of tags) {
      try {
        // Check if tag already exists in the repo
        execSync(`git rev-parse ${item.name}`, { stdio: 'pipe' });
        console.log(`Tag ${item.name} already exists, skipping...`);
      } catch (error) {
        // Tag doesn't exist, proceed with update
        console.log(`Processing new tag: ${item.name}`);
        
        // Read composer.json
        const composerJsonPath = './composer.json';
        let composerJson = JSON.parse(fs.readFileSync(composerJsonPath, 'utf8'));
        
        // Update stability setting
        if (item.name.includes('rc')) {
          composerJson['minimum-stability'] = 'RC';
        } else {
          composerJson['minimum-stability'] = 'stable';
        }
        
        // Update shopware/core version
        composerJson.require['shopware/core'] = item.name;
        
        // Write updated composer.json
        fs.writeFileSync(composerJsonPath, JSON.stringify(composerJson, null, 2));
        
        // Git operations
        execSync('git add composer.json');
        execSync(`git commit -m "Update shopware/core to ${item.name}"`, {stdio: 'inherit'});
        execSync(`git tag -m "Release: ${item.name}" ${item.name}`, {stdio: 'inherit'});
        execSync('git reset --hard origin/trunk', {stdio: 'inherit'});
        
        console.log(`Successfully processed tag: ${item.name}`);
      }
    }
  } catch (error) {
    console.error('An error occurred:', error);
  }
}

await update();
