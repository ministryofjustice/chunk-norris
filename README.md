# Chunk Norris 🥋 
Chunk Norris is a suite of **data cleaning**, **tokenizing**, and **content-preprocessing tools** designed for preparing **WordPress exports** and other web content for **LLM (Large Language Model)** training, fine-tuning, or evaluation.

It focuses on:
- Cleaning messy HTML, shortcodes, and WordPress artifacts  
- Extracting structured content from WordPress posts, pages, and metadata  
- Normalizing, chunking  
- Exporting clean, ready-to-train datasets in text files

---

## What is it actually doing?

Once deployed into the cluster it is set on a cron to run every hour. Every hour it will target the 
https://websitebuilder.service.justice.gov.uk site scraping all the post and pages via the WP API, clean that data
and output it into the s3://cloud-platform-69f5cec4ef010c4aa8746dab4cda322a/wordpress-content/ bucket.

---

## Features

- **Data Cleaning:**  
  Remove boilerplate, markup, embedded scripts, shortcodes, and block markup (`<!-- wp:... -->`).  

- **WordPress Aware:**  
  Purpose-built for WordPress XML exports, REST API responses, and database dumps.  

- **Chunking & Tokenizing:**  
  Split long content intelligently for LLM context windows, with pluggable tokenizers (e.g., tiktoken, nltk, or custom rules).  

- **Language-Agnostic Pipeline:**  
  Core logic in Python with optional PHP tools for WordPress-native preprocessing or plugin data extraction.  

- **Extensible:**  
  Simple modules for adding your own preprocessors, filters, and export formats.  

- **Output Formats:**  
  - Clean text  
  - JSON or JSONL  
  - Tokenized sequences  
  - Metadata-linked chunks  

---

Todo:
- Have the date export in JSON format.
- Fix an env var bug when running locally. Doesn't seem to be picking up that it needs
to use the local var. Working in the k8s cluster though.
- Add in tokenizing text efficiently

---

## Run locally
This has been Dockerized so to run, go into the root via your terminal and run the `make build && make run` command.

## Deploy to an environment

Required
Right now it is configured to only work in the `website-builder-assistant-dev` namespace in CP.
It only needs to be in that because the s3 bucket is hardcoded and permissions for that s3 bucket are given to the pod via
a service account.

To deploy
Switch into the namespace and run `make deploy`. This will setup the cronjob in the namespace which will run every hour.

Manually trigger cron
Run `make trigger` . Watch pods and it will appear.

