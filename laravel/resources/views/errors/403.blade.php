@extends('errors.dmc')

{{-- generic by design: no role names, no exception text — a 403 must not leak why (C4) --}}
@section('code', '403')
@section('title', 'Access denied')
@section('message', "You don't have permission to open this page. If you believe you should, contact the unit administrator.")
