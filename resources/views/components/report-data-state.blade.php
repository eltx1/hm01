@props(['available' => false, 'hasEstimates' => false, 'coverage' => []])
@if(!$available)
Awaiting imported data
@elseif($hasEstimates)
Includes estimates · Awaiting finalization
@else
Finalized imported rows
@endif
@if($available && ($coverage['expected_count'] ?? 0) > 0 && ($coverage['missing_count'] ?? 0) > 0)
 · Partial source coverage
@endif
