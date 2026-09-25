@extends('errors.dmc')

{{-- e.g. GET /logout: sign-out is a form button, not a link (role walkthrough 2026-09-25) --}}
@section('code', '405')
@section('title', "This link can't be opened directly")
@section('message', "That address only works from a button inside the app, so it can't be opened directly. Go back to the app and use the button instead.")
