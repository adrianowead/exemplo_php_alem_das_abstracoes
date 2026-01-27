<?php

# código simples para visualizar opcode
$continuar = true;
$count = 0;

while($continuar) {
    $count += 1;

    $continuar = $count < 20;
}