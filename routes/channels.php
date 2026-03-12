<?php

use Illuminate\Support\Facades\Broadcast;

// Versi 1: Format Lengkap (Sesuai permintaan FE & standar Laravel)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Versi 2: Format Pendek (Hanya untuk jaga-jaga)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
