<?php

return [

    // Tamaño máximo por archivo, en MB. Debe ser menor o igual que
    // upload_max_filesize de PHP y client_max_body_size de Nginx.
    'max_subida_mb' => (int) env('MAX_SUBIDA_MB', 100),

];
