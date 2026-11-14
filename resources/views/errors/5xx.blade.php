@extends('errors::500')

@section('code', (string) $exception->getStatusCode())
