# Imagen oficial de Rust para compilar
FROM rust:1.73 as builder

WORKDIR /app
COPY . .

# Compilar en release
RUN cargo build -p pointercrate-example --release

# Imagen final más liviana
FROM debian:bullseye-slim

WORKDIR /app
COPY --from=builder /app/target/release/pointercrate-example /app/pointercrate-example

# Exponer el puerto (ajústalo si tu app escucha en otro)
EXPOSE 8000

# Ejecutar
CMD ["./pointercrate-example"]
