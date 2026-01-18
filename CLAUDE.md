# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Silo** is a web-based Digital Asset Manager (DAM) for 3D print files (.stl, .3mf).

## Architecture

- **Frontend**: HTML/CSS (JavaScript to be added)
- **Database**: SQLite (schema in `db/schema.sql`)
- **File Storage**: Uploaded models stored in `assets/`

## Project Structure

```
Silo/
├── index.html          # Homepage
├── css/style.css       # Styles
├── assets/             # Uploaded 3D model files
├── db/schema.sql       # Database schema
└── images/             # UI images/icons
```

## Database

Models table stores metadata; actual files go in `assets/`. See `db/schema.sql` for full schema.
