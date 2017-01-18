# Linkbuilding Spider
A PHP project to check if websites are linking to your website

You provide a list of *web pages you are targeting*, *your own websites* and *your competitors' websites*. This tool will check each target to see if they are linking to either you or your competitors.

## Features

 - Uses PHP and SQLite
 - Works out of the box, zero-click installation
 - Uses `curl` for accessing target web pages
 - Caches downloaded pages for a few days

We use this project in a production environment with many people accessing it simultaneously for multiple clients.

## Installation

Install the `source/` directory onto your web server. Access that website using a browser.

Alternatively, you can run this program locally using PHP's built-in web server:

    php -S localhost:8000 -t source/

## Contributing

This project uses Semantic Versioning.
