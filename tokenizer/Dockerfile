FROM php:8.2-cli

# Install Python and pip
RUN apt-get update && \
    apt-get install -y python3 python3-pip && \
    rm -rf /var/lib/apt/lists/*

# Remove Debian's external management restriction
RUN rm /usr/lib/python3.*/EXTERNALLY-MANAGED

# Install tiktoken
RUN pip install --no-cache-dir tiktoken

WORKDIR /app
COPY . /app

CMD ["php", "-a"]
