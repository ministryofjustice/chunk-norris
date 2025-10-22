# Chunk Norris 🥋 
Chunk Norris is a suite of **data cleaning**, **tokenizing**, and **content-preprocessing tools** designed for preparing **WordPress exports** and other web content for **LLM (Large Language Model)** training, fine-tuning, or evaluation.

It focuses on:
- Cleaning messy HTML, shortcodes, and WordPress artifacts  
- Extracting structured content from WordPress posts, pages, and metadata  
- Normalizing, chunking, and tokenizing text efficiently  
- Exporting clean, ready-to-train datasets in text, JSONL, or tokenized formats

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


