<?php

namespace App\Domain\Trust\Registry;

use RuntimeException;

/** The registry couldn't be asked (network, credentials, provider down): try again later. */
final class RegistryUnavailable extends RuntimeException {}
