import sys, tiktoken, json

filename = sys.argv[1]
with open(filename, "r") as f:
    text = f.read()

enc = tiktoken.encoding_for_model("gpt-4o-mini")
tokens = enc.encode(text)

data = {
    "file": filename,
    "token_count": len(tokens),
    "tokens": tokens[:50],
    "decoded": [enc.decode([t]) for t in tokens[:50]]
}

print(json.dumps(data, indent=2))