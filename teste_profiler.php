<?php
// teste.php
$array = range(1, 100000); // Aloca memória
usleep(500000); // Espera 0.5 segundos (Simula CPU/IO)
echo "Processamento concluído!\n";