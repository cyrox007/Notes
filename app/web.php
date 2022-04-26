<?php
    Route::getTrack('/', 'Controller_Main@index');

    Route::getTrack('/edit/(param)', 'Controller_Main@edit');
    Route::getTrack('/delete/(param)', 'Controller_Main@delete');
    
    Route::getTrack('/login', 'Controller_Main@login');
    Route::getTrack('/logout', 'Controller_Main@logout');

    Route::getTrack('/404', 'Controller_Main@404');