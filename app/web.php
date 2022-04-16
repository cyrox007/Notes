<?php
    Route::get("/", "MainController@index");
    Route::get("/login", "MainController@login");
    Route::get("/edit/<id>", "MainController@edit");