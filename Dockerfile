FROM rustlang/rust:nightly as builder

WORKDIR /app
COPY . .

RUN apt-get update && apt-get install -y \
    pkg-config \
    libssl-dev \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*
