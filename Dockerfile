FROM rust:1.81 as builder
RUN rustup install nightly && rustup default nightly

WORKDIR /app
COPY . .

RUN apt-get update && apt-get install -y \
    pkg-config \
    libssl-dev \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN cargo build --release -p pointercrate-example

FROM debian:bullseye-slim
WORKDIR /app

COPY --from=builder /app/target/release/pointercrate-example /app/pointercrate-example

RUN apt-get update && apt-get install -y \
    libssl1.1 \
    libpq5 \
    && rm -rf /var/lib/apt/lists/*

EXPOSE 8000
CMD ["./pointercrate-example"]
