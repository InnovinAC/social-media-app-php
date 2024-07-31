<?php

    namespace App\app\framework;
    class AntiBrokeness {
        // Amount in Naira
        public function __construct(private int $initialAmount) {
            if ($this->initialAmount < 500) {
                echo("You are broke :( \n");
                echo("You have only " . $this->initialAmount . " in your bank \n");
            }
        }

        // Where the magic occurs :)
        public function multiplyFunds(): AntiBrokeness {
            $this->initialAmount *= 2;
            echo("Your money has doubled \n");
            return $this;
        }

        public function myBalance() {
            print 'Wow, your balance is ' . $this->initialAmount;
        }
    }


    $antiBrokeness = new AntiBrokeness(200);
    $antiBrokeness->multiplyFunds()->multiplyFunds()->multiplyFunds()->multiplyFunds()->multiplyFunds()->multiplyFunds()->myBalance();
